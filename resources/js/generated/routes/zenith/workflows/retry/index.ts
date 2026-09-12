import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowRetryController::store
* @see src/Http/Controllers/WorkflowRetryController.php:13
* @route '/horizon/workflows/{workflow}/retry'
*/
export const store = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/workflows/{workflow}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowRetryController::store
* @see src/Http/Controllers/WorkflowRetryController.php:13
* @route '/horizon/workflows/{workflow}/retry'
*/
store.url = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{workflow}', parsedArgs.workflow.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\WorkflowRetryController::store
* @see src/Http/Controllers/WorkflowRetryController.php:13
* @route '/horizon/workflows/{workflow}/retry'
*/
store.post = (args: { workflow: string | number } | [workflow: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const retry = {
    store: Object.assign(store, store),
}

export default retry