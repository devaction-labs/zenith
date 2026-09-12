import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\PendingJobsSelectedCancelController::destroy
* @see src/Http/Controllers/PendingJobsSelectedCancelController.php:14
* @route '/horizon/jobs/pending/cancel-selected'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/jobs/pending/cancel-selected',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\PendingJobsSelectedCancelController::destroy
* @see src/Http/Controllers/PendingJobsSelectedCancelController.php:14
* @route '/horizon/jobs/pending/cancel-selected'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\PendingJobsSelectedCancelController::destroy
* @see src/Http/Controllers/PendingJobsSelectedCancelController.php:14
* @route '/horizon/jobs/pending/cancel-selected'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const cancelSelected = {
    destroy: Object.assign(destroy, destroy),
}

export default cancelSelected