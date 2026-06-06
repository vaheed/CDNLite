import { render } from '@testing-library/vue';
import TextInput from './TextInput.vue';

test('uses the field label as the input accessible name', () => {
  const view = render(TextInput, {
    props: {
      modelValue: '',
      help: {
        label: 'Username',
        what: 'Admin account username.',
        works: 'Used to sign in.',
        example: 'admin',
        required: true,
      },
    },
  });

  expect(view.getByLabelText('Username', { exact: true })).toBeInTheDocument();
});
