import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::store
* @see src/Http/Controllers/QueuePauseAllController.php:14
* @route '/horizon/queues/pause-all'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/queues/pause-all',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::store
* @see src/Http/Controllers/QueuePauseAllController.php:14
* @route '/horizon/queues/pause-all'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::store
* @see src/Http/Controllers/QueuePauseAllController.php:14
* @route '/horizon/queues/pause-all'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::destroy
* @see src/Http/Controllers/QueuePauseAllController.php:27
* @route '/horizon/queues/pause-all'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/queues/pause-all',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::destroy
* @see src/Http/Controllers/QueuePauseAllController.php:27
* @route '/horizon/queues/pause-all'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\QueuePauseAllController::destroy
* @see src/Http/Controllers/QueuePauseAllController.php:27
* @route '/horizon/queues/pause-all'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const pauseAll = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default pauseAll