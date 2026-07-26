<?php

declare(strict_types=1);

use App\Enums\Media\Type;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Asset\AttachExistingAssetTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

function assetForAttach(Workspace $workspace, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
    ], $attributes));
}

test('attaches an existing workspace asset once and reports idempotent repeats', function () {
    $asset = assetForAttach($this->workspace, [
        'original_filename' => 'apex.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'Car clipping the apex',
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($asset) {
            $json->where('asset_id', $asset->id)
                ->where('attached', true)
                ->where('already_attached', false)
                ->has('post')
                ->etc();
        });

    expect($this->post->fresh()->media)
        ->toHaveCount(1)
        ->and(data_get($this->post->fresh()->media, '0.id'))->toBe($asset->id)
        ->and(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('Car clipping the apex');

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'Updated alt should not duplicate the item',
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('attached', false)
            ->where('already_attached', true)
            ->etc());

    expect($this->post->fresh()->media)->toHaveCount(1)
        ->and(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('Car clipping the apex');
});

test('create post then attach existing asset persists media and remains idempotent', function () {
    $asset = assetForAttach($this->workspace, [
        'original_filename' => 'img_7681.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $createResponse = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Test TryPost con asset',
        ]);

    $postId = null;
    $createResponse->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$postId) {
            $postId = $json->toArray()['id'];

            $json->where('content', 'Test TryPost con asset')
                ->where('media', [])
                ->where('status', 'draft')
                ->etc();
        });

    $post = Post::findOrFail($postId);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $postId,
            'asset_id' => $asset->id,
            'alt' => 'Immagine di test selezionata dalla libreria TryPost',
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($asset, $postId) {
            $json->where('asset_id', $asset->id)
                ->where('attached', true)
                ->where('already_attached', false)
                ->where('post.id', $postId)
                ->where('post.media.0.id', $asset->id)
                ->where('post.media.0.original_filename', 'img_7681.jpg')
                ->etc();
        });

    TryPostServer::actingAs($this->user)
        ->tool(GetPostTool::class, ['post_id' => $postId])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('id', $postId)
            ->where('media.0.id', $asset->id)
            ->etc());

    expect($post->fresh()->media)
        ->toHaveCount(1)
        ->and(data_get($post->fresh()->media, '0.id'))->toBe($asset->id);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $postId,
            'asset_id' => $asset->id,
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('attached', false)
            ->where('already_attached', true)
            ->where('post.media.0.id', $asset->id)
            ->etc());

    expect($post->fresh()->media)->toHaveCount(1);
});

test('attach existing asset writes structured operational log without sensitive token data', function () {
    Log::spy();

    $asset = assetForAttach($this->workspace);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($asset): bool {
            return $message === 'post.asset.attach'
                && data_get($context, 'event') === 'post.asset.attach'
                && data_get($context, 'workspace_id') === $this->workspace->id
                && data_get($context, 'post_id') === $this->post->id
                && data_get($context, 'asset_id') === $asset->id
                && data_get($context, 'user_id') === $this->user->id
                && data_get($context, 'attached') === true
                && data_get($context, 'already_attached') === false
                && data_get($context, 'media_count_before') === 0
                && data_get($context, 'media_count_after') === 1
                && is_int(data_get($context, 'duration_ms'))
                && ! array_key_exists('token', $context)
                && ! array_key_exists('authorization', $context);
        });
});

test('does not store alt text for existing non-image assets', function () {
    $asset = assetForAttach($this->workspace, [
        'type' => Type::Video,
        'path' => 'media/video.mp4',
        'original_filename' => 'video.mp4',
        'mime_type' => 'video/mp4',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'ignored for videos',
        ])
        ->assertOk();

    expect(data_get($this->post->fresh()->media, '0.type'))->toBe('video')
        ->and(data_get($this->post->fresh()->media, '0.meta'))->toBeNull();
});

test('rejects cross-workspace assets and posts without mutating the post', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $foreignAsset = assetForAttach($otherWorkspace);
    $localAsset = assetForAttach($this->workspace);
    $foreignPost = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $otherUser->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $foreignAsset->id,
        ])
        ->assertHasErrors();

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $foreignPost->id,
            'asset_id' => $localAsset->id,
        ])
        ->assertHasErrors();

    expect($this->post->fresh()->media)->toHaveCount(0)
        ->and($foreignPost->fresh()->media)->toHaveCount(0);
});

test('rejects read-only users and posts in non-editable states', function () {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $asset = assetForAttach($this->workspace);

    TryPostServer::actingAs($viewer)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors();

    $publishedPost = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $publishedPost->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors();

    expect($this->post->fresh()->media)->toHaveCount(0)
        ->and($publishedPost->fresh()->media)->toHaveCount(0);
});

test('rejects assets that enabled post platforms cannot publish', function () {
    $asset = assetForAttach($this->workspace);
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    PostPlatform::factory()->tiktok()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors();

    expect($this->post->fresh()->media)->toHaveCount(0);
});
