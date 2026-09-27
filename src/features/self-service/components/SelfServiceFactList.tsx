export type SelfServiceFact = {
  label: string;
  value: string;
  testId?: string;
};

type Props = {
  rows: SelfServiceFact[];
  testId?: string;
};

export function SelfServiceFactList({ rows, testId }: Props) {
  if (rows.length === 0) {
    return null;
  }
  return (
    <dl className="pm-leave-detail" data-testid={testId}>
      {rows.map((row) => (
        <div className="pm-leave-detail__row" key={row.label}>
          <dt>{row.label}</dt>
          <dd data-testid={row.testId}>{row.value}</dd>
        </div>
      ))}
    </dl>
  );
}
