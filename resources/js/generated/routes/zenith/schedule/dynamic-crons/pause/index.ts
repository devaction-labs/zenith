import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::store
* @see src/Http/Controllers/DynamicCronPauseController.php:13
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
export const store = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/schedule/dynamic-crons/{cron}/pause',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::store
* @see src/Http/Controllers/DynamicCronPauseController.php:13
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
store.url = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { cron: args }
    }

    if (Array.isArray(args)) {
        args = {
            cron: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        cron: args.cron,
    }

    return store.definition.url
            .replace('{cron}', parsedArgs.cron.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::store
* @see src/Http/Controllers/DynamicCronPauseController.php:13
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
store.post = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::destroy
* @see src/Http/Controllers/DynamicCronPauseController.php:22
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
export const destroy = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/schedule/dynamic-crons/{cron}/pause',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::destroy
* @see src/Http/Controllers/DynamicCronPauseController.php:22
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
destroy.url = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { cron: args }
    }

    if (Array.isArray(args)) {
        args = {
            cron: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        cron: args.cron,
    }

    return destroy.definition.url
            .replace('{cron}', parsedArgs.cron.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronPauseController::destroy
* @see src/Http/Controllers/DynamicCronPauseController.php:22
* @route '/horizon/schedule/dynamic-crons/{cron}/pause'
*/
destroy.delete = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const pause = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default pause