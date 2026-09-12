import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\ExecutingJobController::index
* @see src/Http/Controllers/ExecutingJobController.php:16
* @route '/horizon/executing'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/horizon/executing',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ExecutingJobController::index
* @see src/Http/Controllers/ExecutingJobController.php:16
* @route '/horizon/executing'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ExecutingJobController::index
* @see src/Http/Controllers/ExecutingJobController.php:16
* @route '/horizon/executing'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\ExecutingJobController::index
* @see src/Http/Controllers/ExecutingJobController.php:16
* @route '/horizon/executing'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const executing = {
    index: Object.assign(index, index),
}

export default executing