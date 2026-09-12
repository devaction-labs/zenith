import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\SupervisorScaleController::store
* @see src/Http/Controllers/SupervisorScaleController.php:17
* @route '/horizon/supervisors/{supervisor}/scale'
*/
export const store = (args: { supervisor: string | number } | [supervisor: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/supervisors/{supervisor}/scale',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SupervisorScaleController::store
* @see src/Http/Controllers/SupervisorScaleController.php:17
* @route '/horizon/supervisors/{supervisor}/scale'
*/
store.url = (args: { supervisor: string | number } | [supervisor: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { supervisor: args }
    }

    if (Array.isArray(args)) {
        args = {
            supervisor: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        supervisor: args.supervisor,
    }

    return store.definition.url
            .replace('{supervisor}', parsedArgs.supervisor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SupervisorScaleController::store
* @see src/Http/Controllers/SupervisorScaleController.php:17
* @route '/horizon/supervisors/{supervisor}/scale'
*/
store.post = (args: { supervisor: string | number } | [supervisor: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const scale = {
    store: Object.assign(store, store),
}

export default scale