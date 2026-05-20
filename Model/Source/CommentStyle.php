<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Visual treatment of the optional checkout comment. Two styles mirror
 * Amasty's offering (and the same options BED's badge style uses).
 */
class CommentStyle implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'as_is',          'label' => __('As is (plain text)')],
            ['value' => 'magento_notice', 'label' => __('Magento notice (highlighted)')],
        ];
    }
}
