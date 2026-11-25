import { render, screen } from "@testing-library/react";
import { expect, it } from "vitest";
import { FieldError, ValidationSummary } from "@/components/form-field-errors";

it("API項目エラーを該当入力の隣に表示し、最初の項目へフォーカスする", () => {
  const errors = { startDate: ["開始日を入力してください。"] };
  render(<form><ValidationSummary errors={errors} /><label>開始日<input name="startDate" aria-invalid="true" aria-describedby="error-startDate" /><FieldError errors={errors} field="startDate" /></label></form>);
  expect(screen.getByRole("textbox")).toHaveFocus();
  expect(screen.getByRole("textbox")).toHaveAccessibleDescription("開始日を入力してください。");
  expect(screen.getByRole("alert")).toHaveTextContent("開始日を入力してください。");
});
