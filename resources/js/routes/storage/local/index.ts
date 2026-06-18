import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119
* @route '/private/{path}'
*/
export const upload = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: upload.url(args, options),
    method: 'put',
})

upload.definition = {
    methods: ["put"],
    url: '/private/{path}',
} satisfies RouteDefinition<["put"]>

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119
* @route '/private/{path}'
*/
upload.url = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return upload.definition.url
            .replace('{path}', parsedArgs.path.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119
* @route '/private/{path}'
*/
upload.put = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: upload.url(args, options),
    method: 'put',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119
* @route '/private/{path}'
*/
const uploadForm = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: upload.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:119
* @route '/private/{path}'
*/
uploadForm.put = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: upload.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

upload.form = uploadForm

const local = {
    upload: Object.assign(upload, upload),
}

export default local