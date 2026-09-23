import { QuestionForm } from "@/components/bank/QuestionForm";
import { QuestionBankIcon } from "@/components/icons";
import { PageHeader } from "@/components/ui/PageHeader";

export default function NewBankQuestionPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        Icon={QuestionBankIcon}
        title="سؤال جديد"
        description="الوسوم الأربعة إلزامية — عليها يُبنى تحليل الأخطاء لاحقاً، وبنكٌ يُوسَم بتساهلٍ اليوم ميزةٌ لا يمكن بناؤها غداً."
      />

      <QuestionForm />
    </div>
  );
}
