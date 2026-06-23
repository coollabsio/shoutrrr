import { Form, Head } from '@inertiajs/react';

import PasskeyVerify from '@/components/auth/passkey-verify';
import PasswordInput from '@/components/auth/password-input';
import InputError from '@/components/common/input-error';
import OrSeparator from '@/components/common/or-separator';
import TextLink from '@/components/common/text-link';
import SocialLoginList from '@/components/socialite/social-login-list';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { SocialProviderOption } from '@/types/auth';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister?: boolean;
    registrationDisabledMessage?: string | null;
    providers?: SocialProviderOption[];
    invitation?: string;
    defaultLogin?: {
        email: string;
        password: string;
    };
};

export default function Login({
    status,
    canResetPassword,
    canRegister = true,
    registrationDisabledMessage,
    providers = [],
    invitation,
    defaultLogin,
}: Props) {
    return (
        <>
            <Head title="Log in" />

            {providers.length > 0 ? (
                <div>
                    <div className="flex flex-col gap-3">
                        <SocialLoginList
                            providers={providers}
                            invitation={invitation}
                        />
                        <PasskeyVerify showSeparator={false} />
                    </div>

                    <OrSeparator />
                </div>
            ) : (
                <PasskeyVerify />
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    defaultValue={defaultLogin?.email}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot your password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                    defaultValue={defaultLogin?.password}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {canRegister ? (
                            <div className="text-center text-sm text-muted-foreground">
                                Don't have an account?{' '}
                                <TextLink
                                    href={register(
                                        invitation
                                            ? { query: { invitation } }
                                            : undefined,
                                    )}
                                    tabIndex={5}
                                >
                                    Sign up
                                </TextLink>
                            </div>
                        ) : (
                            <div className="text-center text-sm text-muted-foreground">
                                {registrationDisabledMessage}
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
    brandText: 'Shoutrrr',
};
