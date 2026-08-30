import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\AuditController::index
* @see src/Http/Controllers/AuditController.php:17
* @route '/horizon/audit'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/horizon/audit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\AuditController::index
* @see src/Http/Controllers/AuditController.php:17
* @route '/horizon/audit'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\AuditController::index
* @see src/Http/Controllers/AuditController.php:17
* @route '/horizon/audit'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \DevactionLabs\HorizonNewDawn\Http\Controllers\AuditController::index
* @see src/Http/Controllers/AuditController.php:17
* @route '/horizon/audit'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const audit = {
    index: Object.assign(index, index),
}

export default audit