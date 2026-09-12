<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Dashboard;

enum HorizonStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Inactive = 'inactive';
    case Unavailable = 'unavailable';
}
