<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay as ExceptionDayResource;
use Magento\Framework\Model\AbstractModel;

class ExceptionDay extends AbstractModel implements ExceptionDayInterface
{
    protected $_eventPrefix = 'etechflow_dd_exception_day';

    protected function _construct(): void
    {
        $this->_init(ExceptionDayResource::class);
    }

    public function getExceptionId(): ?int
    {
        $v = $this->getData(self::EXCEPTION_ID);
        return $v === null ? null : (int) $v;
    }

    public function setExceptionId(?int $id): self
    {
        $this->setData(self::EXCEPTION_ID, $id);
        return $this;
    }

    public function getStoreIds(): string
    {
        return (string) $this->getData(self::STORE_IDS);
    }

    public function setStoreIds(string $csv): self
    {
        $this->setData(self::STORE_IDS, $csv);
        return $this;
    }

    public function getDayType(): string
    {
        $type = (string) $this->getData(self::DAY_TYPE);
        return $type === self::TYPE_WORKING ? self::TYPE_WORKING : self::TYPE_HOLIDAY;
    }

    public function setDayType(string $type): self
    {
        $this->setData(self::DAY_TYPE, $type);
        return $this;
    }

    public function getDay(): int
    {
        return (int) $this->getData(self::DAY);
    }

    public function setDay(int $day): self
    {
        $this->setData(self::DAY, $day);
        return $this;
    }

    public function getMonth(): int
    {
        return (int) $this->getData(self::MONTH);
    }

    public function setMonth(int $month): self
    {
        $this->setData(self::MONTH, $month);
        return $this;
    }

    public function getYear(): ?int
    {
        $v = $this->getData(self::YEAR);
        return $v === null || $v === '' ? null : (int) $v;
    }

    public function setYear(?int $year): self
    {
        $this->setData(self::YEAR, $year);
        return $this;
    }

    public function getDescription(): ?string
    {
        $v = $this->getData(self::DESCRIPTION);
        return $v === null ? null : (string) $v;
    }

    public function setDescription(?string $description): self
    {
        $this->setData(self::DESCRIPTION, $description);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $v = $this->getData(self::CREATED_AT);
        return $v === null ? null : (string) $v;
    }

    public function getUpdatedAt(): ?string
    {
        $v = $this->getData(self::UPDATED_AT);
        return $v === null ? null : (string) $v;
    }
}