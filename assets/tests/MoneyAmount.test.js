import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MoneyAmount from '@/components/MoneyAmount.vue';

describe('MoneyAmount', () => {
    it.each([
        [-1250, 'amount-debit'],
        [1250, 'amount-credit'],
    ])('%i centimes ⇒ classe %s', (amount, cls) => {
        const wrapper = mount(MoneyAmount, { props: { amount } });
        expect(wrapper.classes()).toContain(cls);
        expect(wrapper.text().replace(/[\u00A0\u202F]/g, ' ')).toContain('12,50 €');
    });

    it('sans montant : tiret, sans couleur', () => {
        const wrapper = mount(MoneyAmount);
        expect(wrapper.text()).toBe('—');
        expect(wrapper.classes()).not.toContain('amount-debit');
        expect(wrapper.classes()).not.toContain('amount-credit');
    });
});
