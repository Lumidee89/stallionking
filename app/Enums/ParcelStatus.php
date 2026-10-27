<?php

namespace App\Enums;

enum ParcelStatus: string
{
    case Booked = 'booked';
    case Received = 'received';
    case Packaged = 'packaged';
    case Scanned = 'scanned';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Received => 'Received in-store',
            self::Packaged => 'Packaged',
            self::Scanned => 'Scanned & ready',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Exception => 'Delivery exception',
        };
    }

    /** The only transitions the system will ever accept. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Booked => [self::Received],
            self::Received => [self::Packaged],
            self::Packaged => [self::Scanned],
            self::Scanned => [self::OutForDelivery],
            self::OutForDelivery => [self::Delivered, self::Exception],
            self::Exception => [self::Booked], // re-book keeps the same tracking number
            self::Delivered => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
