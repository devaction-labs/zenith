<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard;

enum SupervisorScalingState: string
{
    case Up = 'up';
    case Down = 'down';
    case Steady = 'steady';
}
