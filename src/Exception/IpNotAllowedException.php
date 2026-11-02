<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The request came from an IP address the project does not allow (auth.ip_forbidden).
 * Payout-key endpoints accept only whitelisted IPs: an empty whitelist allows none.
 * Add the server IP to the whitelist in the dashboard.
 */
final class IpNotAllowedException extends PermissionDeniedException {}
