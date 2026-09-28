import { useEffect, useState } from "react";

/** Bumps once per minute and when the app returns to the foreground (live shift countdowns). */
export function useIstanbulMinuteClock(): number {
  const [tick, setTick] = useState(() => Date.now());

  useEffect(() => {
    const bump = () => setTick(Date.now());
    const intervalId = window.setInterval(bump, 60_000);
    const onVisibility = () => {
      if (document.visibilityState === "visible") {
        bump();
      }
    };
    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("pageshow", bump);
    return () => {
      window.clearInterval(intervalId);
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("pageshow", bump);
    };
  }, []);

  return tick;
}
