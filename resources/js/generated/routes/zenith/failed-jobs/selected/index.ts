import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedClearController::destroy
* @see src/Http/Controllers/FailedJobsSelectedClearController.php:14
* @route '/horizon/failed/selected'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/failed/selected',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedClearController::destroy
* @see src/Http/Controllers/FailedJobsSelectedClearController.php:14
* @route '/horizon/failed/selected'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobsSelectedClearController::destroy
* @see src/Http/Controllers/FailedJobsSelectedClearController.php:14
* @route '/horizon/failed/selected'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const selected = {
    destroy: Object.assign(destroy, destroy),
}

export default selected