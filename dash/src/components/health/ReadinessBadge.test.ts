import { fireEvent, render } from '@testing-library/vue';
import ReadinessBadge from './ReadinessBadge.vue';

test('shows readiness state and emits click', async () => {
  const view = render(ReadinessBadge, { props: { label: 'Core', status: 'warning' } });
  await fireEvent.click(view.getByRole('button', { name: /core warning/i }));
  expect(view.emitted().click).toHaveLength(1);
});

test('shows unavailable readiness as unknown instead of warning', () => {
  const view = render(ReadinessBadge, { props: { label: 'Edge ready', status: 'unknown' } });
  expect(view.getByText('Edge ready Unknown')).toBeInTheDocument();
});
