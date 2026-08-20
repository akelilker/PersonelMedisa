import { Component, type ErrorInfo, type ReactNode } from "react";
import { getAppPublicPath } from "../config/public-base";
import { logError } from "../logging/error-logger";

type ErrorBoundaryProps = {
  children: ReactNode;
  /** When true, only reload is offered (no router-dependent home link). */
  rootLevel?: boolean;
};

type ErrorBoundaryState = {
  hasError: boolean;
};

function resolveHomeHref(): string {
  const pub = getAppPublicPath();
  if (!pub) {
    return "/";
  }
  return pub.endsWith("/") ? pub : `${pub}/`;
}

/**
 * Recoverable React boundary.
 * "Ana ekrana don" resets hasError and hard-navigates home so the tree remounts
 * (Link-only would leave hasError=true and lock the fallback).
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    logError({
      message: error.message || "React render/lifecycle error",
      error_stack: error.stack,
      component_stack: info.componentStack ? String(info.componentStack) : undefined,
      source: this.props.rootLevel ? "RootErrorBoundary" : "ErrorBoundary",
      error_code: "REACT_RENDER"
    });
  }

  private handleReload = (): void => {
    window.location.reload();
  };

  private handleGoHome = (): void => {
    // Explicit recovery: clear boundary lock, then remount via hard navigation.
    this.setState({ hasError: false }, () => {
      window.location.assign(resolveHomeHref());
    });
  };

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <section className="states-page" role="alert">
          <h2>Bir sorun olustu</h2>
          <p>
            {this.props.rootLevel
              ? "Uygulama beklenmedik bir hata verdi. Sayfayi yenileyerek tekrar deneyin."
              : "Uygulama bu ekranda beklenmedik bir hata verdi. Sayfayi yenileyebilir veya ana ekrana donebilirsiniz."}
          </p>
          <p>
            <button type="button" onClick={this.handleReload}>
              Sayfayi yenile
            </button>
          </p>
          {!this.props.rootLevel ? (
            <p>
              <button type="button" onClick={this.handleGoHome}>
                Ana ekrana don
              </button>
            </p>
          ) : null}
        </section>
      );
    }

    return this.props.children;
  }
}
