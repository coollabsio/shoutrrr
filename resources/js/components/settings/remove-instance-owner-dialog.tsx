import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Owner = {
    name: string;
};

export default function RemoveInstanceOwnerDialog({
    owner,
    onClose,
    onConfirm,
}: {
    owner: Owner | null;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={owner !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove instance owner</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove {owner?.name} as an
                        instance owner? They will no longer be able to manage
                        instance-wide settings.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={onConfirm}
                    >
                        Remove owner
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
