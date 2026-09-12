import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedRetryController::store
* @see src/Http/Controllers/FailedJobsSelectedRetryController.php:14
* @route '/horizon/failed/retry-selected'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/failed/retry-selected',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedRetryController::store
* @see src/Http/Controllers/FailedJobsSelectedRetryController.php:14
* @route '/horizon/failed/retry-selected'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedRetryController::store
* @see src/Http/Controllers/FailedJobsSelectedRetryController.php:14
* @route '/horizon/failed/retry-selected'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

const retrySelected = {
    store: Object.assign(store, store),
}

export default retrySelected