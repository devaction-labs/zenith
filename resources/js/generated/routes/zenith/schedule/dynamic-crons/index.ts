import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
import pause from './pause'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::store
* @see src/Http/Controllers/DynamicCronController.php:15
* @route '/horizon/schedule/dynamic-crons'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/schedule/dynamic-crons',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::store
* @see src/Http/Controllers/DynamicCronController.php:15
* @route '/horizon/schedule/dynamic-crons'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::store
* @see src/Http/Controllers/DynamicCronController.php:15
* @route '/horizon/schedule/dynamic-crons'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::update
* @see src/Http/Controllers/DynamicCronController.php:28
* @route '/horizon/schedule/dynamic-crons/{cron}'
*/
export const update = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/horizon/schedule/dynamic-crons/{cron}',
} satisfies RouteDefinition<["put"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::update
* @see src/Http/Controllers/DynamicCronController.php:28
* @route '/horizon/schedule/dynamic-crons/{cron}'
*/
update.url = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{cron}', parsedArgs.cron.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::update
* @see src/Http/Controllers/DynamicCronController.php:28
* @route '/horizon/schedule/dynamic-crons/{cron}'
*/
update.put = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::destroy
* @see src/Http/Controllers/DynamicCronController.php:41
* @route '/horizon/schedule/dynamic-crons/{cron}'
*/
export const destroy = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/schedule/dynamic-crons/{cron}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::destroy
* @see src/Http/Controllers/DynamicCronController.php:41
* @route '/horizon/schedule/dynamic-crons/{cron}'
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
* @see \DevactionLabs\Zenith\Http\Controllers\DynamicCronController::destroy
* @see src/Http/Controllers/DynamicCronController.php:41
* @route '/horizon/schedule/dynamic-crons/{cron}'
*/
destroy.delete = (args: { cron: string | number } | [cron: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const dynamicCrons = {
    store: Object.assign(store, store),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    pause: Object.assign(pause, pause),
}

export default dynamicCrons