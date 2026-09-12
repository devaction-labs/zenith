import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import clearAll from './clear-all'
import retryAll from './retry-all'
import retrySelected from './retry-selected'
import selected from './selected'
import retry from './retry'
import explain from './explain'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::index
* @see src/Http/Controllers/FailedJobController.php:20
* @route '/horizon/failed'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/horizon/failed',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::index
* @see src/Http/Controllers/FailedJobController.php:20
* @route '/horizon/failed'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::index
* @see src/Http/Controllers/FailedJobController.php:20
* @route '/horizon/failed'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::index
* @see src/Http/Controllers/FailedJobController.php:20
* @route '/horizon/failed'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::show
* @see src/Http/Controllers/FailedJobController.php:77
* @route '/horizon/failed/{job}'
*/
export const show = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/horizon/failed/{job}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::show
* @see src/Http/Controllers/FailedJobController.php:77
* @route '/horizon/failed/{job}'
*/
show.url = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { job: args }
    }

    if (Array.isArray(args)) {
        args = {
            job: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        job: args.job,
    }

    return show.definition.url
            .replace('{job}', parsedArgs.job.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::show
* @see src/Http/Controllers/FailedJobController.php:77
* @route '/horizon/failed/{job}'
*/
show.get = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::show
* @see src/Http/Controllers/FailedJobController.php:77
* @route '/horizon/failed/{job}'
*/
show.head = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::destroy
* @see src/Http/Controllers/FailedJobController.php:89
* @route '/horizon/failed/{job}'
*/
export const destroy = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/horizon/failed/{job}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::destroy
* @see src/Http/Controllers/FailedJobController.php:89
* @route '/horizon/failed/{job}'
*/
destroy.url = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { job: args }
    }

    if (Array.isArray(args)) {
        args = {
            job: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        job: args.job,
    }

    return destroy.definition.url
            .replace('{job}', parsedArgs.job.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\FailedJobController::destroy
* @see src/Http/Controllers/FailedJobController.php:89
* @route '/horizon/failed/{job}'
*/
destroy.delete = (args: { job: string | number } | [job: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const failedJobs = {
    index: Object.assign(index, index),
    clearAll: Object.assign(clearAll, clearAll),
    retryAll: Object.assign(retryAll, retryAll),
    retrySelected: Object.assign(retrySelected, retrySelected),
    selected: Object.assign(selected, selected),
    show: Object.assign(show, show),
    destroy: Object.assign(destroy, destroy),
    retry: Object.assign(retry, retry),
    explain: Object.assign(explain, explain),
}

export default failedJobs