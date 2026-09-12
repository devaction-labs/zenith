import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::store
* @see src/Http/Controllers/SchedulePauseController.php:14
* @route '/horizon/schedule/pause'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/schedule/pause',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::store
* @see src/Http/Controllers/SchedulePauseController.php:14
* @route '/horizon/schedule/pause'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::store
* @see src/Http/Controllers/SchedulePauseController.php:14
* @route '/horizon/schedule/pause'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::destroy
* @see src/Http/Controllers/SchedulePauseController.php:27
* @route '/horizon/schedule/pause'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/schedule/pause',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::destroy
* @see src/Http/Controllers/SchedulePauseController.php:27
* @route '/horizon/schedule/pause'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\SchedulePauseController::destroy
* @see src/Http/Controllers/SchedulePauseController.php:27
* @route '/horizon/schedule/pause'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const pause = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default pause