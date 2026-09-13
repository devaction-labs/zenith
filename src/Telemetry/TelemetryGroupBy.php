<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * How the live throughput chart splits its series.
 *
 * `State` plots one series per outcome (processed/failed/released/timed
 * out), summed across every queue, class, and node. `Queue` / `JobClass` /
 * `Node` instead plot one series per that dimension's busiest values in the
 * selected window (see `TelemetryMetricsReader::MAX_SERIES`), each series
 * summing every outcome for that value.
 */
enum TelemetryGroupBy: string
{
    case State = 'state';
    case Queue = 'queue';
    case JobClass = 'class';
    case Node = 'node';
    case Connection = 'connection';

    public function dimension(): ?TelemetryDimension
    {
        return match ($this) {
            self::State => null,
            self::Queue => TelemetryDimension::Queue,
            self::JobClass => TelemetryDimension::JobClass,
            self::Node => TelemetryDimension::Node,
            self::Connection => TelemetryDimension::Connection,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::State => 'State',
            self::Queue => 'Queue',
            self::JobClass => 'Job class',
            self::Node => 'Node',
            self::Connection => 'Connection',
        };
    }
}
