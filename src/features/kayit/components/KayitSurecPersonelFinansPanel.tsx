import type { Dispatch, FormEvent, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { FinansMaliFieldsState } from "../../../lib/finans/finans-create-commit";

type KayitSurecPersonelFinansPanelProps = {
  title: string;
  personelLabel: string;
  formId: string;
  fieldNamePrefix: string;
  fields: FinansMaliFieldsState;
  setFields: Dispatch<SetStateAction<FinansMaliFieldsState>>;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  errorMessage: string | null;
  isSubmitting: boolean;
  isKalemLocked?: boolean;
};

export function KayitSurecPersonelFinansPanel({
  title,
  personelLabel,
  formId,
  fieldNamePrefix,
  fields,
  setFields,
  onSubmit,
  errorMessage,
  isSubmitting,
  isKalemLocked = false
}: KayitSurecPersonelFinansPanelProps) {
  return (
    <div>
      <p className="workspace-empty-hint">
        <strong>{title}</strong> — {personelLabel}
      </p>
      <form id={formId} className="finans-form-grid" onSubmit={onSubmit}>
        <FormField
          label="Dönem"
          name={`${fieldNamePrefix}-donem`}
          type="month"
          value={fields.donem}
          onChange={(value) => setFields((prev) => ({ ...prev, donem: value }))}
          required
        />
        {isKalemLocked ? (
          <FormField
            label="Kalem Turu"
            name={`${fieldNamePrefix}-kalem-display`}
            value="CEZA"
            onChange={() => undefined}
            disabled
          />
        ) : (
          <FormField
            label="Kalem Turu"
            name={`${fieldNamePrefix}-kalem`}
            value={fields.kalemTuru}
            onChange={(value) => setFields((prev) => ({ ...prev, kalemTuru: value }))}
            required
          />
        )}
        <FormField
          label="Tutar"
          name={`${fieldNamePrefix}-tutar`}
          type="number"
          min={0.01}
          step="0.01"
          value={fields.tutar}
          onChange={(value) => setFields((prev) => ({ ...prev, tutar: value }))}
          required
        />
        <FormField
          label="Açıklama"
          name={`${fieldNamePrefix}-aciklama`}
          value={fields.aciklama}
          onChange={(value) => setFields((prev) => ({ ...prev, aciklama: value }))}
        />
        {errorMessage ? <p className="finans-form-error">{errorMessage}</p> : null}
      </form>
      <div className="universal-btn-group workspace-form-actions">
        <button type="submit" form={formId} className="universal-btn-save" disabled={isSubmitting}>
          {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
        </button>
      </div>
    </div>
  );
}
