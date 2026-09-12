import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
import pause from './pause'
import run from './run'
import dynamicCrons from './dynamic-crons'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleController::index
* @see src/Http/Controllers/ScheduleController.php:18
* @route '/horizon/schedule'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/horizon/schedule',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleController::index
* @see src/Http/Controllers/ScheduleController.php:18
* @route '/horizon/schedule'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleController::index
* @see src/Http/Controllers/ScheduleController.php:18
* @route '/horizon/schedule'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleController::index
* @see src/Http/Controllers/ScheduleController.php:18
* @route '/horizon/schedule'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const schedule = {
    index: Object.assign(index, index),
    pause: Object.assign(pause, pause),
    run: Object.assign(run, run),
    dynamicCrons: Object.assign(dynamicCrons, dynamicCrons),
}

export default schedule