import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \DevactionLabs\Zenith\Http\Controllers\JobRetryController::store
* @see src/Http/Controllers/JobRetryController.php:13
* @route '/horizon/jobs/{type}/{job}/retry'
*/
export const store = (args: { type: string | number, job: string | number } | [type: string | number, job: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/horizon/jobs/{type}/{job}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \DevactionLabs\Zenith\Http\Controllers\JobRetryController::store
* @see src/Http/Controllers/JobRetryController.php:13
* @route '/horizon/jobs/{type}/{job}/retry'
*/
store.url = (args: { type: string | number, job: string | number } | [type: string | number, job: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            type: args[0],
            job: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        type: args.type,
        job: args.job,
    }

    return store.definition.url
            .replace('{type}', parsedArgs.type.toString())
            .replace('{job}', parsedArgs.job.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \DevactionLabs\Zenith\Http\Controllers\JobRetryController::store
* @see src/Http/Controllers/JobRetryController.php:13
* @route '/horizon/jobs/{type}/{job}/retry'
*/
store.post = (args: { type: string | number, job: string | number } | [type: string | number, job: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const retry = {
    store: Object.assign(store, store),
}

export default retry