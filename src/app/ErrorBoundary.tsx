import { Component, type ErrorInfo, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { logError } from "../logging/error-logger";

type ErrorBoundaryProps = {
  children: ReactNode;
};

type ErrorBoundaryState = {
  hasError: boolean;
};

export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    const stack = error.stack ?? (info.componentStack ? String(info.componentStack) : undefined);
    logError({
      message: error.message || "React render/lifecycle error",
      stack,
      source: "ErrorBoundary"
    });
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <section className="states-page" role="alert">
          <h2>Bir sorun olustu</h2>
          <p>Uygulama bu ekranda beklenmedik bir hata verdi. Sayfayi yenileyebilir veya ana modullere donebilirsiniz.</p>
          <p>
            <button type="button" onClick={() => window.location.reload()}>
              Sayfayi yenile
            </button>
          </p>
          <p>
            <Link to="/personeller">Personellere don</Link>
          </p>
        </section>
      );
    }

    return this.props.children;
  }
}
