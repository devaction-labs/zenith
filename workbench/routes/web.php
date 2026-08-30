<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): array => [
    'package' => 'devaction-labs/horizon-new-dawn',
    'horizon' => route('horizon.index'),
]);
