<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

enum JobOutcome: string
{
    /** The handler returned; the job was removed. */
    case Succeeded = 'succeeded';

    /** The handler threw; the job will run again later. */
    case Retried = 'retried';

    /** The handler threw on the last attempt, or the job could not be decoded; it is kept as failed. */
    case Failed = 'failed';

    /** The lease expired before the job finished; another worker may have taken it. */
    case Lost = 'lost';
}
