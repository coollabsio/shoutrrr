import { cn } from "@/lib/utils"
import { Loader2 } from '@/components/ui/icons';

function Spinner({ className, ...props }: React.ComponentProps<"svg">) {
  return (
    <Loader2 role="status" aria-label="Loading" className={cn("size-4 animate-spin", className)} {...props} />
  )
}

export { Spinner }
