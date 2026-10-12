<?php
namespace WaldorfshopSeoTest\Services;

use Plenty\Modules\Tag\Contracts\TagRepositoryContract;
use Plenty\Modules\Tag\Contracts\TagRelationshipRepositoryContract;

/** Adds one order tag; never replaces a customer's or an order's other tags. */
class ClimateOrderTagService
{
    const TAG_NAME = 'Klimaauftrag';
    private $tags;
    private $relationships;

    public function __construct(TagRepositoryContract $tags, TagRelationshipRepositoryContract $relationships)
    {
        $this->tags = $tags;
        $this->relationships = $relationships;
    }

    public function mark(int $orderId): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('A persisted order is required.');
        }
        // Configure the tag for orders in the Plenty backend before enabling.
        $tag = $this->tags->getTagByName(self::TAG_NAME);
        if (!$tag || (int)$tag->id <= 0) {
            throw new \RuntimeException('Order tag Klimaauftrag is missing.');
        }
        if (!$this->relationships->findRelationship((int)$tag->id, $orderId, 'order')) {
            $this->relationships->create([
                'tagId' => (int)$tag->id,
                'tagType' => 'order',
                'relationshipValue' => $orderId
            ]);
        }
    }
}
