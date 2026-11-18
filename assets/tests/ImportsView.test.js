import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const clearMock = vi.fn();
const uploadImportsMock = vi.fn();

vi.mock('@/api/imports', () => ({
    listImports: vi.fn(async () => ({ items: [], total: 0 })),
    uploadImports: (...args) => uploadImportsMock(...args),
}));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }));

import ImportsView from '@/views/ImportsView.vue';

const FileUploadStub = defineComponent({
    name: 'FileUpload',
    emits: ['uploader'],
    setup(_, { emit, expose }) {
        expose({ clear: clearMock });
        return () =>
            h('button', {
                class: 'fu-stub',
                onClick: () => emit('uploader', { files: [new File(['x'], 'releve.pdf', { type: 'application/pdf' })] }),
            });
    },
});

function mountView() {
    return mount(ImportsView, {
        global: {
            stubs: {
                Card: { template: '<div><slot name="title" /><slot name="content" /></div>' },
                FileUpload: FileUploadStub,
                DataTable: true,
                Column: true,
                Tag: true,
            },
        },
    });
}

describe('ImportsView', () => {
    it('vide la liste des fichiers (clear) après un envoi réussi', async () => {
        uploadImportsMock.mockResolvedValue({ reports: [], postProcessing: { transfersMatched: 0, categorized: 0 } });
        const wrapper = mountView();
        await flushPromises();

        await wrapper.find('.fu-stub').trigger('click');
        await flushPromises();

        expect(uploadImportsMock).toHaveBeenCalledTimes(1);
        expect(clearMock).toHaveBeenCalledTimes(1);
    });

    it('ne vide pas la liste si l’envoi échoue', async () => {
        uploadImportsMock.mockRejectedValue(new Error('réseau'));
        clearMock.mockClear();
        const wrapper = mountView();
        await flushPromises();

        await wrapper.find('.fu-stub').trigger('click');
        await flushPromises();

        expect(uploadImportsMock).toHaveBeenCalledTimes(1);
        expect(clearMock).not.toHaveBeenCalled();
    });
});
