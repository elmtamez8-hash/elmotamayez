import { QuestionForm } from "@/components/bank/QuestionForm";

export default function NewBankQuestionPage() {
  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">سؤال جديد</h1>
        <p className="text-sm text-ink-muted">
          الوسوم الأربعة إلزامية — عليها يُبنى تحليل الأخطاء لاحقاً، وبنكٌ يُوسَم بتساهلٍ اليوم
          ميزةٌ لا يمكن بناؤها غداً.
        </p>
      </header>

      <QuestionForm />
    </div>
  );
}
