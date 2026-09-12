import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleRunController::store
* @see src/Http/Controllers/ScheduleRunController.php:14
* @route '/horizon/schedule/{event}/run'
*/
export const store = (args: { event: string | number } | [event: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/schedule/{event}/run',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleRunController::store
* @see src/Http/Controllers/ScheduleRunController.php:14
* @route '/horizon/schedule/{event}/run'
*/
store.url = (args: { event: string | number } | [event: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { event: args }
    }

    if (Array.isArray(args)) {
        args = {
            event: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        event: args.event,
    }

    return store.definition.url
            .replace('{event}', parsedArgs.event.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ScheduleRunController::store
* @see src/Http/Controllers/ScheduleRunController.php:14
* @route '/horizon/schedule/{event}/run'
*/
store.post = (args: { event: string | number } | [event: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const run = {
    store: Object.assign(store, store),
}

export default run