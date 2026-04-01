import { AppProviders } from "./providers";
import { AppRoutes } from "./routes";
import { ErrorBoundary } from "./ErrorBoundary";

export function App() {
  return (
    <AppProviders>
      <ErrorBoundary>
        <AppRoutes />
      </ErrorBoundary>
    </AppProviders>
  );
}
