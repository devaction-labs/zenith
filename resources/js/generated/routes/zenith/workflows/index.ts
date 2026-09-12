import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import cancel from './cancel'
import retry from './retry'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::index
* @see src/Http/Controllers/WorkflowController.php:15
* @route '/horizon/workflows'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/horizon/workflows',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::index
* @see src/Http/Controllers/WorkflowController.php:15
* @route '/horizon/workflows'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::index
* @see src/Http/Controllers/WorkflowController.php:15
* @route '/horizon/workflows'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::index
* @see src/Http/Controllers/WorkflowController.php:15
* @route '/horizon/workflows'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::show
* @see src/Http/Controllers/WorkflowController.php:24
* @route '/horizon/workflows/{workflow}'
*/
export const show = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/horizon/workflows/{workflow}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::show
* @see src/Http/Controllers/WorkflowController.php:24
* @route '/horizon/workflows/{workflow}'
*/
show.url = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { workflow: args }
    }

    if (Array.isArray(args)) {
        args = {
            workflow: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        workflow: args.workflow,
    }

    return show.definition.url
            .replace('{workflow}', parsedArgs.workflow.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::show
* @see src/Http/Controllers/WorkflowController.php:24
* @route '/horizon/workflows/{workflow}'
*/
show.get = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowController::show
* @see src/Http/Controllers/WorkflowController.php:24
* @route '/horizon/workflows/{workflow}'
*/
show.head = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const workflows = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    cancel: Object.assign(cancel, cancel),
    retry: Object.assign(retry, retry),
}

export default workflows