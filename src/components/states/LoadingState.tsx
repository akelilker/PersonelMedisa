type LoadingStateProps = {
  label?: string;
};

export function LoadingState({ label = "Yükleniyor..." }: LoadingStateProps) {
  return (
    <div className="state-card state-loading">
      <p>{label}</p>
    </div>
  );
}
