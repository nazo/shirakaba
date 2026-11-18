<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

/**
 * A forwarding header that the proxies in front of the application set or overwrite.
 *
 * Only headers the proxy controls may be trusted: a header it passes through unchanged
 * still carries whatever the client sent. nginx, for example, appends to X-Forwarded-For
 * but forwards a client's X-Forwarded-Host and Forwarded untouched unless configured.
 */
enum ForwardedHeader
{
    /** X-Forwarded-For: the client address. */
    case For;

    /** X-Forwarded-Proto: the scheme the client used. */
    case Proto;

    /** X-Forwarded-Host: the host the client asked for. */
    case Host;

    /** X-Forwarded-Port: the port the client connected to. */
    case Port;

    /** RFC 7239 Forwarded, which carries all of the above; cannot be combined with X-Forwarded-*. */
    case Forwarded;
}
