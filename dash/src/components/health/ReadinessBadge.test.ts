import { fireEvent, render } from '@testing-library/vue';
import ReadinessBadge from './ReadinessBadge.vue';

test('shows readiness state and emits click', async () => {
  const view = render(ReadinessBadge, { props: { label: 'Core', status: 'warning' } });
  await fireEvent.click(view.getByRole('button', { name: /core warning/i }));
  expect(view.emitted().click).toHaveLength(1);
});
