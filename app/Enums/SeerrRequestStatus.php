<?php

namespace App\Enums;

/**
 * Finer-grained status for an Seerr request, stored alongside {@see LibraryState}
 * to distinguish "pending approval" from "approved" while the coarser state is
 * still Requested/Pending (i.e. before Sonarr/Radarr starts downloading).
 */
enum SeerrRequestStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';

    public static function fromSeerr(?int $status): self
    {
        return match ($status) {
            2 => self::Approved,
            default => self::PendingApproval,
        };
    }
}
