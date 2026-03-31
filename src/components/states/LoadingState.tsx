type LoadingStateProps = {
  label?: string;
};

export function LoadingState({ label = "Yukleniyor..." }: LoadingStateProps) {
  return (
    <div className="state-card state-loading">
      <p>{label}</p>
    </div>
  );
}
