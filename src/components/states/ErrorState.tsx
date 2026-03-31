type ErrorStateProps = {
  message: string;
  onRetry?: () => void;
};

export function ErrorState({ message, onRetry }: ErrorStateProps) {
  return (
    <div className="state-card state-error">
      <p>{message}</p>
      {onRetry ? (
        <button type="button" className="universal-btn-aux" onClick={onRetry}>
          Tekrar Dene
        </button>
      ) : null}
    </div>
  );
}
