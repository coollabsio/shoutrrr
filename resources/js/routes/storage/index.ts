import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import localA91488 from './local'
import publicMethodC5d39d from './public'
/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
export const local = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: local.url(args, options),
    method: 'get',
})

local.definition = {
    methods: ["get","head"],
    url: '/private/{path}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
local.url = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { path: args }
    }

    if (Array.isArray(args)) {
        args = {
            path: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        path: args.path,
    }

    return local.definition.url
            .replace('{path}', parsedArgs.path.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
local.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: local.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
local.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: local.url(args, options),
    method: 'head',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
const localForm = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: local.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
localForm.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: local.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/private/{path}'
*/
localForm.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: local.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

local.form = localForm

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
export const publicMethod = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: publicMethod.url(args, options),
    method: 'get',
})

publicMethod.definition = {
    methods: ["get","head"],
    url: '/storage/{path}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
publicMethod.url = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { path: args }
    }

    if (Array.isArray(args)) {
        args = {
            path: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        path: args.path,
    }

    return publicMethod.definition.url
            .replace('{path}', parsedArgs.path.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
publicMethod.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: publicMethod.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
publicMethod.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: publicMethod.url(args, options),
    method: 'head',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
const publicMethodForm = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: publicMethod.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
publicMethodForm.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: publicMethod.url(args, options),
    method: 'get',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:111
* @route '/storage/{path}'
*/
publicMethodForm.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: publicMethod.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

publicMethod.form = publicMethodForm

const storage = {
    local: Object.assign(local, localA91488),
    public: Object.assign(publicMethod, publicMethodC5d39d),
}

export default storage