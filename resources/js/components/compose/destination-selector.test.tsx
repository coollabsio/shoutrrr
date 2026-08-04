import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import DestinationSelector from '@/components/compose/destination-selector';
import type { Account, Destination } from '@/types/compose';

function account(id: string, handle: string): Account {
    return {
        id,
        platform: 'x',
        handle,
        display_name: handle,
        avatar_url: null,
        status: 'active',
        max_text_length: 280,
        x_premium: false,
    };
}

const accounts = [account('a1', '@one'), account('a2', '@two')];

function open(destination: Destination) {
    const onChange = vi.fn();
    render(
        <DestinationSelector
            accounts={accounts}
            sets={[]}
            destination={destination}
            onChange={onChange}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Post destination' }));

    return onChange;
}

describe('DestinationSelector "All accounts" toggle', () => {
    it('deselects everything when all accounts are selected', () => {
        const onChange = open({ kind: 'all' });

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'none' });
    });

    it('selects everything when nothing is selected', () => {
        const onChange = open({ kind: 'none' });

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'all' });
    });

    it('lets the last account be deselected down to none', () => {
        const onChange = open({ kind: 'account', id: 'a1' });

        fireEvent.click(screen.getByRole('button', { name: /@one/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'none' });
    });
});

describe('DestinationSelector trigger label', () => {
    it('reads "No accounts" for an empty selection', () => {
        render(
            <DestinationSelector
                accounts={accounts}
                sets={[]}
                destination={{ kind: 'none' }}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Post destination' }),
        ).toHaveTextContent('No accounts');
    });
});
